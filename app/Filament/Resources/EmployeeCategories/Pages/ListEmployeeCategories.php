<?php

namespace App\Filament\Resources\EmployeeCategories\Pages;

use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeCategories extends ListRecords
{
    protected static string $resource = EmployeeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
