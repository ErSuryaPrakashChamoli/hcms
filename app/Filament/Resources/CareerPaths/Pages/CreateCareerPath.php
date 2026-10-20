<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCareerPath extends PeopleCreateRecord
{
    protected static string $resource = CareerPathResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
