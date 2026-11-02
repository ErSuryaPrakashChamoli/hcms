<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Platform\Services\SettingsRepository;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #5, "Apply leave for selected employees"; owner decision TIM-5 / D-TX7, off by
 * default through attendance.bulk_apply_leave): for each selected absent (or half) day, leave of the chosen type is
 * requested on the employee's behalf through Leaves::request and approved by the HR user (leave.manage) with the reason.
 * The leave rules apply as for any request — eligibility, balance and negative-balance limits, notice, locked or closed
 * days — and a day they refuse is listed with the reason, never forced. One's own days are refused.
 */
final class ApplyLeaveOperation extends BaseBulkOperation
{
    public const SETTING = 'attendance.bulk_apply_leave';

    public const HALF_DAYS = ['skip' => 'Leave half days out', 'first_half' => 'First half', 'second_half' => 'Second half'];

    public function key(): string
    {
        return 'attendance_records.apply_leave';
    }

    public function label(): string
    {
        return 'Apply leave';
    }

    public function description(): string
    {
        return 'Applies the chosen leave on each selected absent day for the employee and approves it as HR with this reason. Balances, eligibility and limits apply; days the leave rules refuse are listed with the reason.';
    }

    public function model(): string
    {
        return AttendanceRecord::class;
    }

    public function permission(): string
    {
        return 'leave.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-calendar-days';
    }

    public static function enabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get(self::SETTING, false);
    }

    public function schema(): array
    {
        return [
            Select::make('leave_type_id')->label('Leave type')->required()
                ->options(fn () => LeaveType::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('half_days')->label('On half days')->options(self::HALF_DAYS)->default('skip')->required()
                ->helperText('Which half the leave covers on a half day (the half the employee was away).'),
            Textarea::make('reason')->label('Reason')->required()->maxLength(255),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('Applying leave in bulk is switched off (Setup → Time office).');
        }
        $type = LeaveType::query()->where('status', 'active')->find((int) ($data['leave_type_id'] ?? 0));
        if ($type === null) {
            throw new RuntimeException('Choose an active leave type.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for applying the leave.');
        }
        $half = (string) ($data['half_days'] ?? 'skip');

        return ['leave_type_id' => (int) $type->id, 'half_days' => array_key_exists($half, self::HALF_DAYS) ? $half : 'skip', 'reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var AttendanceRecord $record */
        if (! self::enabled()) {
            return 'Applying leave in bulk is switched off.';
        }
        if (! in_array($record->status, ['absent', 'half_day'], true)) {
            return 'Only absent and half days take leave here (this day is '.str_replace('_', ' ', (string) $record->status).').';
        }
        if ((int) $record->employee?->user_id === (int) $actor->id) {
            return 'You cannot apply and approve your own leave.';
        }

        return null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AttendanceRecord $record */
        $session = $record->status === 'half_day' ? (string) $input['half_days'] : 'full';
        if ($session === 'skip') {
            throw new RuntimeException('A half day: choose which half the leave covers to include half days.');
        }
        $type = LeaveType::query()->findOrFail((int) $input['leave_type_id']);
        $date = $record->date->toDateString();
        $leaves = app(Leaves::class);
        $request = $leaves->request($record->employee, $type, $date, $date, (string) $input['reason'], $session, $session, null, $actor);
        if ($request->status === 'pending') {
            $request = $leaves->approve($request, 'Applied by HR on an absent day: '.$input['reason'], $actor);
        }

        return "{$type->name} ".($session === 'full' ? '' : str_replace('_', ' ', $session).' ')."on {$record->date->format('d M Y')}: ".$request->status;
    }

    public function recordLabel(Model $record): string
    {
        return mb_substr(parent::recordLabel($record).' · '.$record->getAttribute('date')?->format('d M Y'), 0, 191);
    }
}
