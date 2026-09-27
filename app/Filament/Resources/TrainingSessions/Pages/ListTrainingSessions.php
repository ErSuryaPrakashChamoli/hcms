<?php

namespace App\Filament\Resources\TrainingSessions\Pages;

use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingSessions extends ListRecords
{
    protected static string $resource = TrainingSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Schedule session')];
    }
}
