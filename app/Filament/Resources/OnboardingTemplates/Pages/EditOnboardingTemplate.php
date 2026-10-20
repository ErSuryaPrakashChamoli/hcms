<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditOnboardingTemplate extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = OnboardingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
