<?php

namespace App\Filament\Resources\BusinessRegions\Pages;

use App\Filament\Resources\BusinessRegions\BusinessRegionResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListBusinessRegions extends PeopleListRecords
{
    protected static string $resource = BusinessRegionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
