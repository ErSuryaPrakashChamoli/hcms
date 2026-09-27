<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOnboardingTemplate extends CreateRecord
{
    protected static string $resource = OnboardingTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return OnboardingTemplateResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
