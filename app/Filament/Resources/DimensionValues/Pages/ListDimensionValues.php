<?php

namespace App\Filament\Resources\DimensionValues\Pages;

use App\Filament\Resources\DimensionValues\DimensionValueResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListDimensionValues extends PeopleListRecords
{
    protected static string $resource = DimensionValueResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
