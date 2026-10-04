<?php

namespace App\Filament\Resources\OnboardingPlans\Pages;

use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListOnboardingPlans extends PeopleListRecords
{
    protected static string $resource = OnboardingPlanResource::class;
}
