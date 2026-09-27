<?php

namespace App\Filament\Resources\BusinessUnits\Pages;

use App\Filament\Resources\BusinessUnits\BusinessUnitResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBusinessUnit extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = BusinessUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
