<?php

namespace App\Domain\Assets\Listeners;

use App\Domain\Assets\Services\AssetEntitlements;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;

/**
 * HROne setup parity AS-B: the requests raised without anyone asking — a joiner's entitled assets when they join
 * (employee.joined, under an asset policy), and returns of everything a leaving employee holds when their exit is
 * initiated (by default, with the exceptions of Setup → Asset; AssetRequests::raiseForExit), cancelled again if the exit
 * is withdrawn.
 */
final class AssetRequestTriggers
{
    public function onLifecycle(EmployeeLifecycleChanged $event): void
    {
        if ($event->to === LifecycleState::Joined && ($employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($event->employee->getKey())) !== null) {
            app(AssetEntitlements::class)->provisionJoiner($employee);
        }
    }

    public function onExit(ExitEvent $event): void
    {
        if ($event->employee === null || ! $event->subject instanceof ExitCase) {
            return;
        }
        match ($event->name) {
            'exit.initiated' => app(AssetRequests::class)->raiseForExit($event->employee, $event->subject),
            'exit.withdrawn' => app(AssetRequests::class)->cancelForExit($event->employee, $event->subject),
            default => null,
        };
    }
}
