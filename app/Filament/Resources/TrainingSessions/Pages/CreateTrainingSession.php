<?php

namespace App\Filament\Resources\TrainingSessions\Pages;

use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateTrainingSession extends PeopleCreateRecord
{
    protected static string $resource = TrainingSessionResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
