<?php

namespace App\Filament\Resources\EmploymentTypes\Pages;

use App\Filament\Resources\EmploymentTypes\EmploymentTypeResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmploymentType extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = EmploymentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
