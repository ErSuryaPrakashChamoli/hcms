<?php

namespace App\Domain\Onboarding\Events;

use App\Domain\Onboarding\Models\OnboardingTask;
use Illuminate\Foundation\Events\Dispatchable;

final class OnboardingTaskAssigned
{
    use Dispatchable;

    public function __construct(public readonly OnboardingTask $task) {}
}
