<?php

namespace App\Filament\Resources\WorkSchedules\Pages;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditWorkSchedule extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = WorkScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
