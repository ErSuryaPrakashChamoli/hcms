<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLearningPaths extends ListRecords
{
    protected static string $resource = LearningPathResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
