<?php

namespace App\Filament\Resources\JobFamilies\Pages;

use App\Filament\Resources\JobFamilies\JobFamilyResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListJobFamilies extends PeopleListRecords
{
    protected static string $resource = JobFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
