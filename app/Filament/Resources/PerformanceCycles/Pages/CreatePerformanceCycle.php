<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePerformanceCycle extends CreateRecord
{
    protected static string $resource = PerformanceCycleResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
