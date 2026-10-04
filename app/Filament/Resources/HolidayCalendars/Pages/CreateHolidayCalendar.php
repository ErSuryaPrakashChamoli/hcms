<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateHolidayCalendar extends PeopleCreateRecord
{
    protected static string $resource = HolidayCalendarResource::class;

    protected function getRedirectUrl(): string
    {
        return HolidayCalendarResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
