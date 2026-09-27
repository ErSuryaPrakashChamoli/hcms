<?php

namespace App\Filament\Resources\JobFamilies\Pages;

use App\Filament\Resources\JobFamilies\JobFamilyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobFamilies extends ListRecords
{
    protected static string $resource = JobFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
