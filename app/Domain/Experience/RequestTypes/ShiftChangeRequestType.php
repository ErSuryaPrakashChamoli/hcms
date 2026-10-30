<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;

/** HROne parity RQ-1: Change shift (a time-office request kind). */
final class ShiftChangeRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'shift_change';
    }

    public function label(): string
    {
        return 'Change shift';
    }

    public function description(): string
    {
        return 'Work a different shift on chosen days';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public function keywords(): array
    {
        return ['shift', 'change shift', 'shift change', 'roster'];
    }

    protected function extra(TimeOfficeRequest $record): ?string
    {
        return $record->shift?->name;
    }
}
