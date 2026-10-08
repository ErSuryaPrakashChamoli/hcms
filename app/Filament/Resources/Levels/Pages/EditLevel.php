<?php

namespace App\Filament\Resources\Levels\Pages;

use App\Filament\Resources\Levels\LevelResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditLevel extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = LevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
