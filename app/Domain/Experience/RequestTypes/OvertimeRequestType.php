<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;

/** HROne parity RQ-1: Overtime (a time-office request kind). */
final class OvertimeRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'overtime';
    }

    public function label(): string
    {
        return 'Overtime';
    }

    public function description(): string
    {
        return 'Claim extra time worked on a day';
    }

    public function icon(): string
    {
        return 'heroicon-o-plus-circle';
    }

    public function keywords(): array
    {
        return ['overtime', 'ot', 'extra hours'];
    }

    protected function extra(TimeOfficeRequest $record): ?string
    {
        return $record->minutes ? $record->minutes.' min' : null;
    }
}
