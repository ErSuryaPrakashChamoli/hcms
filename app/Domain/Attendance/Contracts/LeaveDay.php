<?php

namespace App\Domain\Attendance\Contracts;

/** What Attendance needs to know about an approved leave on a date (leave-ready contract, Phase 2 §42). */
final class LeaveDay
{
    public function __construct(
        public readonly int $leaveRequestId,
        public readonly string $session,   // full | first_half | second_half
        public readonly bool $isPaid,
        public readonly ?string $leaveTypeCode = null,
    ) {}

    public function isFullDay(): bool
    {
        return $this->session === 'full';
    }
}
