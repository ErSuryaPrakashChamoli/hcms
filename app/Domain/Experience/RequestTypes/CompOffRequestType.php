<?php

namespace App\Domain\Experience\RequestTypes;

/** HROne parity RQ-1: Comp-off (a time-office request kind). */
final class CompOffRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'comp_off';
    }

    public function label(): string
    {
        return 'Comp-off';
    }

    public function description(): string
    {
        return 'Claim a day off for work on a holiday or weekly off';
    }

    public function icon(): string
    {
        return 'heroicon-o-gift';
    }

    public function keywords(): array
    {
        return ['comp off', 'comp-off', 'compensatory off', 'holiday work'];
    }
}
