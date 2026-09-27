<?php

namespace App\Domain\Onboarding\Events;

use App\Domain\Onboarding\Models\OnboardingPlan;
use Illuminate\Foundation\Events\Dispatchable;

final class OnboardingCompleted
{
    use Dispatchable;

    public function __construct(public readonly OnboardingPlan $plan) {}
}
