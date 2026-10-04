<?php

namespace App\Filament\Resources\LearningProviders\Pages;

use App\Filament\Resources\LearningProviders\LearningProviderResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageLearningProviders extends PeopleManageRecords
{
    protected static string $resource = LearningProviderResource::class;

    protected function getHeaderActions(): array
    {
        return LearningProviderResource::headerActions();
    }
}
