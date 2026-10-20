<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListPerformanceCycles extends PeopleListRecords
{
    protected static string $resource = PerformanceCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
