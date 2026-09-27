<?php

namespace App\Filament\Resources\Grades\Pages;

use App\Filament\Resources\Grades\GradeResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGrade extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = GradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
