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
            self::Exited, self::Alumni => 'gray',
        };
    }

    public function isEmployed(): bool
    {
        return ! in_array($this, [self::PreEmployee, self::Preboarding, self::Exited, self::Alumni], true);
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
