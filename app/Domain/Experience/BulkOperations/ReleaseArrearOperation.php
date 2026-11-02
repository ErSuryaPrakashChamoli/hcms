<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Services\AttendanceArrears;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #10, "Process attendance arrear"): release each selected held attendance arrear
 * (taken again by the next run) through AttendanceArrears — attendance.arrears, the employee in scope, one reason.
 */
final class ReleaseArrearOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'attendance_arrears.release';
    }

    public function label(): string
    {
        return 'Release arrears';
    }

    public function description(): string
    {
        return 'Releases each selected held arrear: it is pending again and taken by the next run. Settled arrears never change.';
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
        return 'heroicon-m-play-circle';
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
        return $record->getAttribute('status') === 'held' ? null : 'Only a held arrear can be released (this one is '.(AttendanceArrear::STATUSES[$record->getAttribute('status')] ?? $record->getAttribute('status')).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AttendanceArrear $record */
        app(AttendanceArrears::class)->release($record, $actor, (string) $input['reason']);

        return 'Arrear of '.$record->date->format('d M Y').' released';
    }

    public function recordLabel(Model $record): string
    {
        return mb_substr(parent::recordLabel($record).' · '.$record->getAttribute('date')?->format('d M Y'), 0, 191);
    }
}
