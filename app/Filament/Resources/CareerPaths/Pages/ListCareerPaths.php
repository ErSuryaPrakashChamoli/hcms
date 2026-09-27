<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCareerPaths extends ListRecords
{
    protected static string $resource = CareerPathResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
