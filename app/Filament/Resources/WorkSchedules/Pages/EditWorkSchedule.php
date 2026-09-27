<?php

namespace App\Filament\Resources\WorkSchedules\Pages;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkSchedule extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = WorkScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
