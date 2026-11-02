<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Services\AttendanceArrears;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #10, "Unprocess attendance arrear"): hold each selected pending attendance arrear
 * (left out until released) through AttendanceArrears — attendance.arrears, the employee in scope, one reason.
 */
final class HoldArrearOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'attendance_arrears.hold';
    }

    public function label(): string
    {
        return 'Hold arrears';
    }

    public function description(): string
    {
        return 'Holds each selected pending arrear: it is left out until it is released. Settled arrears never change.';
    }

    public function model(): string
    {
        return AttendanceArrear::class;
    }

    public function permission(): string
    {
        return 'attendance.arrears';
    }

    public function icon(): string
    {
        return 'heroicon-m-pause-circle';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Reason')->required()->maxLength(255)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason.');
        }

        return ['reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['employee.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return $record->getAttribute('status') === 'pending' ? null : 'Only a pending arrear can be held (this one is '.(AttendanceArrear::STATUSES[$record->getAttribute('status')] ?? $record->getAttribute('status')).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AttendanceArrear $record */
        app(AttendanceArrears::class)->hold($record, $actor, (string) $input['reason']);

        return 'Arrear of '.$record->date->format('d M Y').' held';
    }

    public function recordLabel(Model $record): string
    {
        return mb_substr(parent::recordLabel($record).' · '.$record->getAttribute('date')?->format('d M Y'), 0, 191);
    }
}
