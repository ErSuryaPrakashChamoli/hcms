<?php

namespace App\Filament\Resources\CostCentres\Pages;

use App\Filament\Resources\CostCentres\CostCentreResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditCostCentre extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = CostCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
