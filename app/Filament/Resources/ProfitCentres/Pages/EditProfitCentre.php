<?php

namespace App\Filament\Resources\ProfitCentres\Pages;

use App\Filament\Resources\ProfitCentres\ProfitCentreResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfitCentre extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = ProfitCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
