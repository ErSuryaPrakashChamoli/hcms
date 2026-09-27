<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHolidayCalendar extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = HolidayCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
