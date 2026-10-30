<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Attendance\Models\TimeOfficeRequest;

/** HROne parity RQ-1: On duty (a time-office request kind). */
final class OnDutyRequestType extends TimeOfficeRequestType
{
    protected function kind(): string
    {
        return 'on_duty';
    }

    public function label(): string
    {
        return 'On duty';
    }

    public function description(): string
    {
        return 'Working away from your usual place: client visit, field work, training';
    }

    public function icon(): string
    {
        return 'heroicon-o-briefcase';
    }

    public function keywords(): array
    {
        return ['on duty', 'od', 'outdoor', 'field', 'client visit'];
    }

    protected function extra(TimeOfficeRequest $record): ?string
    {
        return $record->category ?: null;
    }
}
