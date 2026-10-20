<?php

namespace App\Filament\Resources\ProfitCentres\Pages;

use App\Filament\Resources\ProfitCentres\ProfitCentreResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditProfitCentre extends PeopleEditRecord
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
