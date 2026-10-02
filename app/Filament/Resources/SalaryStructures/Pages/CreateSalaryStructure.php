<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Domain\Compensation\Services\CompensationStructures;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSalaryStructure extends CreateRecord
{
    protected static string $resource = SalaryStructureResource::class;

    /** Phase 11: a structure starts with a draft version 1 (components are added there, then approved). */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CompensationStructures::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
