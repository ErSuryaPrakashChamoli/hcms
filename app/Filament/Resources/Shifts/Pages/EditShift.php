<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditShift extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = ShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
