<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;

/** HROne parity RQ-1: Week-off change (a time-office request kind). */
final class WeekOffChangeRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'week_off_change';
    }

    public function label(): string
    {
        return 'Week-off change';
    }

    public function description(): string
    {
        return 'Work a weekly off and take another day off instead';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-path';
    }

    public function keywords(): array
    {
        return ['week off', 'weekly off', 'week-off change', 'swap'];
    }

    protected function extra(TimeOfficeRequest $record): ?string
    {
        return $record->swap_date ? 'off on '.$record->swap_date->format('d M Y') : null;
    }
}
