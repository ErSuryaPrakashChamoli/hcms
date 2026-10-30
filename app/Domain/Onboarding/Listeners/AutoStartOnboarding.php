<?php

namespace App\Domain\Onboarding\Listeners;

use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Onboarding\Services\Onboarding;
use RuntimeException;

/** When someone enters preboarding or joins, start the matching onboarding plan (and induction programme) if none exists. */
final class AutoStartOnboarding
{
    public function __construct(private readonly Onboarding $onboarding) {}

    public function handle(EmployeeLifecycleChanged $event): void
    {
        if (! in_array($event->to, [LifecycleState::Preboarding, LifecycleState::Joined, LifecycleState::Onboarding], true)) {
            return;
        }

        $employee = $event->employee;

        // HROne setup parity 08.2: the onboarding checklist and the induction programme each start once, from the best
        // matching template of their kind; a template that starts N days before joining waits for peopleos:onboarding:start-due.
        foreach (array_keys((array) config('peopleos.onboarding.kinds', ['onboarding' => 'Onboarding'])) as $kind) {
            if ($employee->onboardingPlans()->where('kind', $kind)->exists()) {
                continue;
            }
            $template = $this->onboarding->templateFor($employee, $kind);
            if ($template === null || ! $this->onboarding->startsNow($template, $employee)) {
                continue;
            }

            try {
                $this->onboarding->start($employee, $template, 'Auto-started on '.$event->to->getLabel());
            } catch (RuntimeException) {
                // Nothing matched or a plan already exists; HR can start one manually.
            }
        }
    }
}
