<?php

namespace App\Filament\Resources\OnboardingPlans\Pages;

use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

class ListOnboardingPlans extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = OnboardingPlanResource::class;

    public static function registerKey(): string
    {
        return 'onboarding_plans';
    }

    public static function presetViews(): array
    {
        return [
            'onboarding' => ['label' => 'Onboarding in progress', 'filters' => ['status' => ['value' => 'in_progress'], 'kind' => ['value' => 'onboarding']]],
            'inductions' => ['label' => 'Inductions in progress', 'filters' => ['status' => ['value' => 'in_progress'], 'kind' => ['value' => 'induction']]],
            'completed' => ['label' => 'Completed', 'filters' => ['status' => ['value' => 'completed']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['onboarding_in_progress', 'overdue_onboarding_tasks', 'pending_joining_documents'];
    }

    protected function getHeaderActions(): array
    {
        return $this->registerToolActions();
    }
}
