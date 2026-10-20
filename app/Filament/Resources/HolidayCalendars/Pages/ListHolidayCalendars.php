<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListHolidayCalendars extends PeopleListRecords
{
    protected static string $resource = HolidayCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
