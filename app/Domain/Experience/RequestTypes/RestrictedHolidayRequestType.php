<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;

/** HROne parity RQ-1: Restricted holiday (a time-office request kind). */
final class RestrictedHolidayRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'restricted_holiday';
    }

    public function label(): string
    {
        return 'Restricted holiday';
    }

    public function description(): string
    {
        return 'Choose one of your optional holidays';
    }

    public function icon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public function keywords(): array
    {
        return ['restricted holiday', 'rh', 'optional holiday', 'floating holiday'];
    }

    protected function extra(TimeOfficeRequest $record): ?string
    {
        return $record->holiday?->name;
    }
}
