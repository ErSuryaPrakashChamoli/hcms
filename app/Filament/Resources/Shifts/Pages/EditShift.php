<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShift extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = ShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
