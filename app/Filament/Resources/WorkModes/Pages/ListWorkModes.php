<?php

namespace App\Filament\Resources\WorkModes\Pages;

use App\Filament\Resources\WorkModes\WorkModeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkModes extends ListRecords
{
    protected static string $resource = WorkModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
