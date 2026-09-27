<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPerformanceCycles extends ListRecords
{
    protected static string $resource = PerformanceCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
