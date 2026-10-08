<?php

namespace App\Filament\Resources\EmployeeCategories\Pages;

use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListEmployeeCategories extends PeopleListRecords
{
    protected static string $resource = EmployeeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
