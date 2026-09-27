<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOnboardingTemplate extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = OnboardingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
