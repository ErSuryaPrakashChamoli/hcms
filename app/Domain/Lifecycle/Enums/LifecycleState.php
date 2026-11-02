<?php

namespace App\Domain\Lifecycle\Enums;

use App\Domain\Audit\Enums\AuditAction;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Blueprint §19. Allowed transitions live in config('peopleos.lifecycle.transitions'). */
enum LifecycleState: string implements HasColor, HasLabel
{
    case PreEmployee = 'pre_employee';
    case Preboarding = 'preboarding';
    case Onboarding = 'onboarding';
    case Joined = 'joined';
    case Probation = 'probation';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case NoticePeriod = 'notice_period';
    case Exited = 'exited';
    case Alumni = 'alumni';
    // HROne parity WO-4 (owner decision WRK-1 / D-WO1): an upcoming joiner who did not join. An end state reached only from
    // the pre-joining states, through UpcomingJoiners::markNotJoining (a reason from the not-joining reasons); the record is
    // kept for retention and rehire checks and is treated like someone never employed.
    case NotJoined = 'not_joined';

    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PreEmployee, self::Preboarding, self::Onboarding => 'info',
            self::Joined, self::Probation => 'warning',
            self::Confirmed, self::Active => 'success',
            self::OnLeave => 'gray',
            self::Suspended, self::NoticePeriod => 'danger',
            self::Exited, self::Alumni, self::NotJoined => 'gray',
        };
    }

    public function isEmployed(): bool
    {
        return ! in_array($this, [self::PreEmployee, self::Preboarding, self::Exited, self::Alumni, self::NotJoined], true);
    }

    /** HROne parity WO-4: someone who has not joined yet (an upcoming joiner); "Mark as not joining" is offered from these. */
    public function isPreJoining(): bool
    {
        return in_array($this, [self::PreEmployee, self::Preboarding], true);
    }

    /**
     * SaaS.2: states in which an employee can be in a payroll run. Someone who has not joined yet
     * (pre-employee, preboarding, onboarding: "Mark as joined" is offered from all three) is never
     * paid. An exited employee stays payable for the period in which they left (the run also filters
     * on exit date); alumni are settled through full and final settlement, not payroll.
     */
    public function isPayrollEligible(): bool
    {
        return in_array($this, [self::Joined, self::Probation, self::Confirmed, self::Active, self::OnLeave, self::Suspended, self::NoticePeriod, self::Exited], true);
    }

    /** @return list<string> */
    public static function payrollEligibleValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isPayrollEligible())));
    }

    /** The audit action that best names entering this state (blueprint §65 lifecycle events). */
    public function auditAction(): AuditAction
    {
        return match ($this) {
            self::Joined, self::Probation => AuditAction::Joined,
            self::Confirmed => AuditAction::Confirmed,
            self::NoticePeriod => AuditAction::ExitInitiated,
            self::Exited => AuditAction::ExitCompleted,
            self::Alumni => AuditAction::AlumniCreated,
            default => AuditAction::Update,
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return array_map(
            fn (string $s) => self::from($s),
            config("peopleos.lifecycle.transitions.{$this->value}", []),
        );
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
