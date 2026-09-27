<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHolidayCalendar extends CreateRecord
{
    protected static string $resource = HolidayCalendarResource::class;

    protected function getRedirectUrl(): string
    {
        return HolidayCalendarResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
