<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditHolidayCalendar extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = HolidayCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
