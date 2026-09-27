<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCareerPath extends CreateRecord
{
    protected static string $resource = CareerPathResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
