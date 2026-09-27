<?php

namespace App\Filament\Resources\CostCentres\Pages;

use App\Filament\Resources\CostCentres\CostCentreResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCostCentre extends EditRecord
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
