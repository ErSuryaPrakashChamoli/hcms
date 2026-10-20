<?php

namespace App\Filament\Resources\JobFamilies\Pages;

use App\Filament\Resources\JobFamilies\JobFamilyResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditJobFamily extends PeopleEditRecord
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
