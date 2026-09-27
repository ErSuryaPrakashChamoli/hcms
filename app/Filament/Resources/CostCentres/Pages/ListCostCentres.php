<?php

namespace App\Filament\Resources\CostCentres\Pages;

use App\Filament\Resources\CostCentres\CostCentreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostCentres extends ListRecords
{
    protected static string $resource = CostCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
