<?php

namespace App\Filament\Resources\JobFamilies\Pages;

use App\Filament\Resources\JobFamilies\JobFamilyResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJobFamily extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = JobFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
