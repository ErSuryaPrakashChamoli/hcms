<?php

namespace App\Filament\Resources\Levels\Pages;

use App\Filament\Resources\Levels\LevelResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLevel extends EditRecord
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
