<?php

namespace App\Filament\Resources\TrainingSessions\Pages;

use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingSession extends CreateRecord
{
    protected static string $resource = TrainingSessionResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
