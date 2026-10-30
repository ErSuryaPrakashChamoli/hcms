<?php

namespace App\Domain\Experience\RequestTypes;

/** HROne parity RQ-1: Short leave (a time-office request kind). */
final class ShortLeaveRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'short_leave';
    }

    public function label(): string
    {
        return 'Short leave';
    }

    public function description(): string
    {
        return 'A few hours away within the day';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function keywords(): array
    {
        return ['short leave', 'permission', 'hours', 'early', 'late'];
    }
}
