<?php

namespace App\Filament\Resources\WorkModes\Pages;

use App\Filament\Resources\WorkModes\WorkModeResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditWorkMode extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = WorkModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MasterDeleteAction::make(),
        ];
    }
}
