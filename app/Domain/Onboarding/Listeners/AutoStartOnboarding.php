<?php

namespace App\Domain\Onboarding\Listeners;

use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Onboarding\Services\Onboarding;
use RuntimeException;

/** When someone enters preboarding or joins, start the matching onboarding plan if none exists. */
final class AutoStartOnboarding
{
    public function __construct(private readonly Onboarding $onboarding) {}

    public function handle(EmployeeLifecycleChanged $event): void
    {
        if (! in_array($event->to, [LifecycleState::Preboarding, LifecycleState::Joined, LifecycleState::Onboarding], true)) {
            return;
        }

        $employee = $event->employee;

        if ($employee->onboardingPlan()->exists() || $this->onboarding->templateFor($employee) === null) {
            return;
        }

        try {
            $this->onboarding->start($employee, null, 'Auto-started on '.$event->to->getLabel());
        } catch (RuntimeException) {
            // Nothing matched or a plan already exists; HR can start one manually.
        }
    }
}
