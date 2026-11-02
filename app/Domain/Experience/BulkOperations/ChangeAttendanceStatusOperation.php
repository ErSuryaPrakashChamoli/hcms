<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-6 (matrix 24 §24.8 #9, global action "Change attendance status": From, To, First half, Second half;
 * §24.5.1 #4): the same attendance status — for the whole day or for each half — on every day of a date range for each
 * selected employee, through AttendanceOverrides::setRange (each day through AttendanceOverrides::set, audited with the
 * reason and recalculated). Refused: an employee outside the access scope, one's own attendance; days not employed, locked
 * days and days of a closed attendance month are listed. attendance.override (TX-2; attendance.manage still grants it);
 * the "Change attendance status" page starts it for selections larger than a direct run takes.
 */
final class ChangeAttendanceStatusOperation extends BaseBulkOperation
{
    public const KEY = 'attendance_status.change';

    public const MODES = ['whole' => 'The whole day', 'halves' => 'Each half of the day'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Change attendance status';
    }

    public function description(): string
    {
        return 'Sets the status of every day from the first to the last day for each selected employee — the whole day, or the first and the second half (first half present and second half absent make a half day) — as an HR day override with this reason; each day is recalculated. Employees outside your access and your own attendance are refused; days not employed, locked days and days of a closed attendance month are listed and left as they are.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'attendance.override';
    }

    public function icon(): string
    {
        return 'heroicon-m-adjustments-horizontal';
    }

    /**
     * The status fields shared by the page's wizard, this operation and the attendance record's "Edit": the whole day or
     * each half, the status(es) and — for one day — worked minutes.
     *
     * @return list<mixed>
     */
    public static function statusFields(bool $oneDay = false): array
    {
        $whole = collect(AttendanceOverride::STATUSES)->mapWithKeys(fn (string $s) => [$s => AttendanceOverride::halfLabel($s)])->all();
        $half = collect(AttendanceOverride::HALF_STATUSES)->mapWithKeys(fn (string $s) => [$s => AttendanceOverride::halfLabel($s)])->all();
        $halves = fn (Get $get) => $get('mode') === 'halves';

        return array_values(array_filter([
            Radio::make('mode')->label('Set the status for')->options(self::MODES)->default('whole')->required()->live()->inline(),
            Select::make('status')->label('Status')->options($whole)->native(false)
                ->placeholder($oneDay ? 'Keep the calculated status' : 'Choose a status')
                ->visible(fn (Get $get) => ! $halves($get))->required(fn (Get $get) => ! $oneDay && ! $halves($get)),
            Select::make('first_half')->label('First half')->options($half)->native(false)->visible($halves)->required($halves),
            Select::make('second_half')->label('Second half')->options($half)->native(false)->visible($halves)->required($halves)
                ->helperText('First half present and second half absent make a half day. Approved half-day leave keeps its half.'),
            $oneDay ? TextInput::make('worked_minutes')->label('Worked minutes')->numeric()->minValue(0)->maxValue(1440)->placeholder('Keep the punched hours') : null,
        ]));
    }

    /** @return list<mixed> the date range (days that have started only) */
    public static function rangeFields(?string $from = null, ?string $to = null): array
    {
        return [
            DatePicker::make('from')->label('From')->native(false)->required()->maxDate(today())->default($from),
            DatePicker::make('to')->label('To')->native(false)->required()->afterOrEqual('from')->maxDate(today())->default($to)
                ->helperText('At most '.AttendanceOverrides::MAX_RANGE_DAYS.' days, up to today.'),
        ];
    }

    public function schema(): array
    {
        return [...self::rangeFields(), ...self::statusFields(), Textarea::make('reason')->label('Reason')->required()->maxLength(500)];
    }

    /**
     * The form's answers as the service reads them: [status, first half, second half] from the mode.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    public static function statusesFrom(array $data): array
    {
        $halves = ($data['mode'] ?? null) === 'halves' || (($data['mode'] ?? null) === null && filled($data['first_half'] ?? null));

        return AttendanceOverrides::statuses(
            $halves ? null : (filled($data['status'] ?? null) ? (string) $data['status'] : null),
            $halves ? (filled($data['first_half'] ?? null) ? (string) $data['first_half'] : null) : null,
            $halves ? (filled($data['second_half'] ?? null) ? (string) $data['second_half'] : null) : null,
        );
    }

    /**
     * What the "Edit" form opens with for a day: HR's override of the day if there is one.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(AttendanceRecord $record): array
    {
        $override = AttendanceOverride::query()->where('employee_id', $record->employee_id)->whereDate('date', $record->date->toDateString())->first();
        if ($override?->hasHalves()) {
            return ['mode' => 'halves', 'first_half' => $override->first_half_status, 'second_half' => $override->second_half_status, 'worked_minutes' => $override->worked_minutes];
        }

        return ['mode' => 'whole', 'status' => $override?->status, 'worked_minutes' => $override?->worked_minutes];
    }

    public function prepare(array $data, User $actor): array
    {
        if (empty($data['from']) || empty($data['to'])) {
            throw new RuntimeException('Choose the first and the last day.');
        }
        [$from, $to] = AttendanceOverrides::range((string) $data['from'], (string) $data['to']);
        [$status, $first, $second] = self::statusesFrom($data);
        if ($status === null && $first === null) {
            throw new RuntimeException('Choose a status for the whole day or for each half.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => $status, 'first_half' => $first, 'second_half' => $second, 'reason' => mb_substr($reason, 0, 500)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return $record->user_id !== null && (int) $record->user_id === (int) $actor->id ? 'You cannot change your own attendance.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $result = app(AttendanceOverrides::class)->setRange([$record], (string) $input['from'], (string) $input['to'], $input['status'] ?? null,
            $input['first_half'] ?? null, $input['second_half'] ?? null, (string) $input['reason'], $actor);
        $refused = implode(' ', array_map(fn (array $r) => ($r['dates'] !== null ? $r['dates'].': ' : '').$r['reason'], $result['refused']));
        if ($result['done'] === []) {
            throw new RuntimeException($refused !== '' ? $refused : 'Nothing was changed.');
        }

        return mb_substr($result['done'][0]['message'].($refused !== '' ? ' Refused — '.$refused : ''), 0, 500);
    }
}
