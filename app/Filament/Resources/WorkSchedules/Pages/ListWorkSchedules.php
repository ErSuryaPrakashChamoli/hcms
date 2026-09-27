<?php

namespace App\Filament\Resources\WorkSchedules\Pages;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkSchedules extends ListRecords
{
    protected static string $resource = WorkScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
