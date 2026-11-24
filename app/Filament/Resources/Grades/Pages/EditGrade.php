<?php

namespace App\Filament\Resources\Grades\Pages;

use App\Filament\Resources\Grades\GradeResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditGrade extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = GradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MasterDeleteAction::make(),
        ];
    }
}
