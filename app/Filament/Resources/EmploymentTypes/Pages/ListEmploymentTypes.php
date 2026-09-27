<?php

namespace App\Filament\Resources\EmploymentTypes\Pages;

use App\Filament\Resources\EmploymentTypes\EmploymentTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmploymentTypes extends ListRecords
{
    protected static string $resource = EmploymentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
