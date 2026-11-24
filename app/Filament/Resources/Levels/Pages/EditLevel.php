<?php

namespace App\Filament\Resources\Levels\Pages;

use App\Filament\Resources\Levels\LevelResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditLevel extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = LevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MasterDeleteAction::make(),
        ];
    }
}
