<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnboardingTemplates extends ListRecords
{
    protected static string $resource = OnboardingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
