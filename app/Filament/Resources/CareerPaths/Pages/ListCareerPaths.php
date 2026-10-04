<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListCareerPaths extends PeopleListRecords
{
    protected static string $resource = CareerPathResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
