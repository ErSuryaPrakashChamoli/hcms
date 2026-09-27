<?php

namespace App\Filament\Resources\OnboardingPlans\Pages;

use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use Filament\Resources\Pages\ListRecords;

class ListOnboardingPlans extends ListRecords
{
    protected static string $resource = OnboardingPlanResource::class;
}
