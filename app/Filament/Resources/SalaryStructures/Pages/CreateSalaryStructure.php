<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalaryStructure extends CreateRecord
{
    protected static string $resource = SalaryStructureResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
