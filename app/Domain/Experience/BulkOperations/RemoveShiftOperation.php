<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-5 (matrix 24 §24.2.1 #11 "Inactive" — remove a rostered shift — for many employees): clears each
 * selected employee's rostered days of the range through ShiftRoster::removePattern, so those days follow the work
 * schedule again (past days recalculated); the same refusals as "Assign shift". attendance.roster.
 */
final class RemoveShiftOperation extends BaseBulkOperation
{
    public const KEY = 'roster_board.remove_shift';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Remove rostered days';
    }

    public function description(): string
    {
        return 'Removes each selected employee\'s rostered days from the first to the last day, so those days follow the work schedule again; past days are recalculated. Employees outside your access, not employed then, or with locked days or days of a closed attendance month are refused.';
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
        return 'heroicon-m-arrow-uturn-left';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function schema(): array
    {
        return [
            DatePicker::make('from')->label('From')->native(false)->required(),
            DatePicker::make('to')->label('To')->native(false)->required()->afterOrEqual('from'),
            Textarea::make('reason')->label('Reason')->required()->maxLength(255),
        ];
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
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for removing the rostered days.');
        }

        return ['from' => $from, 'to' => $to, 'reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $result = app(ShiftRoster::class)->removePattern([$record], (string) $input['from'], (string) $input['to'], (string) $input['reason'], $actor);
        if ($result['refused'] !== []) {
            throw new RuntimeException($result['refused'][0]['reason']);
        }

        return $result['done'][0]['message'] ?? 'Removed.';
    }
}
