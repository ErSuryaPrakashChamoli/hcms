<?php

namespace App\Filament\Resources\CostCentres\Pages;

use App\Filament\Resources\CostCentres\CostCentreResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListCostCentres extends PeopleListRecords
{
    protected static string $resource = CostCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
