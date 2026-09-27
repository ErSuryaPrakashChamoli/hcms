<?php

namespace App\Filament\Resources\WorkModes\Pages;

use App\Filament\Resources\WorkModes\WorkModeResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkMode extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = WorkModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
