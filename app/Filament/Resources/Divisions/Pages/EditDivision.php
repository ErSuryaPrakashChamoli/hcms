<?php

namespace App\Filament\Resources\Divisions\Pages;

use App\Filament\Resources\Divisions\DivisionResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDivision extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = DivisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
