<?php

namespace App\Filament\Resources\OnboardingTemplates\Pages;

use App\Filament\Resources\OnboardingTemplates\OnboardingTemplateResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateOnboardingTemplate extends PeopleCreateRecord
{
    protected static string $resource = OnboardingTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return OnboardingTemplateResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
