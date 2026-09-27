<?php

namespace App\Filament\Resources\EmployeeCategories\Pages;

use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeCategory extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = EmployeeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
