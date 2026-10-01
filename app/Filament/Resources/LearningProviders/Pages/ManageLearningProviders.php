<?php

namespace App\Filament\Resources\LearningProviders\Pages;

use App\Filament\Resources\LearningProviders\LearningProviderResource;
use Filament\Resources\Pages\ManageRecords;

class ManageLearningProviders extends ManageRecords
{
    protected static string $resource = LearningProviderResource::class;

    protected function getHeaderActions(): array
    {
        return LearningProviderResource::headerActions();
    }
}
