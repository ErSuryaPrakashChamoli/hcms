<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #6, Run attendance → "Mark present"): an HR day override "present" with one reason
 * on each selected absent or half day, through AttendanceOverrides::set — the same as a day override on the register
 * (attendance.override, the record's `override` policy in scope); locked days and days of a closed month are refused there.
 */
final class MarkPresentOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'attendance_records.mark_present';
    }

    public function label(): string
    {
        return 'Mark present';
    }

    public function description(): string
    {
        return 'Sets each selected absent or half day to present as an HR day override with this reason; the day is recalculated. Locked days and days of a closed month are refused.';
    }

    public function model(): string
    {
        return AttendanceRecord::class;
    }

    public function permission(): string
    {
        return 'attendance.override';
    }

    public function icon(): string
    {
        return 'heroicon-m-check-circle';
    }

    public function color(): string
    {
        return 'success';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Reason')->required()->maxLength(255)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for marking the days present.');
        }

        return ['reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var AttendanceRecord $record */
        if (! in_array($record->status, ['absent', 'half_day'], true)) {
            return 'Only absent and half days are marked present here (this day is '.str_replace('_', ' ', (string) $record->status).').';
        }
        if ((int) $record->employee?->user_id === (int) $actor->id) {
            return 'You cannot change your own attendance.';
        }

        return $actor->can('override', $record) ? null : 'You may not override this day.';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AttendanceRecord $record */
        $day = app(AttendanceOverrides::class)->set($record->employee, $record->date->copy(), 'present', null, (string) $input['reason'], $actor);

        return $record->date->format('d M Y').' marked present (now '.str_replace('_', ' ', (string) $day->status).')';
    }

    public function recordLabel(Model $record): string
    {
        return mb_substr(parent::recordLabel($record).' · '.$record->getAttribute('date')?->format('d M Y'), 0, 191);
    }
}
