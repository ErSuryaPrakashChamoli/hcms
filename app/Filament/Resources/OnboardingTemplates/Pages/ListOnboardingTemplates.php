<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListOnboardingTemplates extends PeopleListRecords
{
    protected static string $resource = OnboardingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
