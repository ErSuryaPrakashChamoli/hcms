<?php

namespace App\Filament\Resources\WorkModes\Pages;

use App\Filament\Resources\WorkModes\WorkModeResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListWorkModes extends PeopleListRecords
{
    protected static string $resource = WorkModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
