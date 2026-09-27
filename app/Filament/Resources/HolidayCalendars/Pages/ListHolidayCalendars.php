<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHolidayCalendars extends ListRecords
{
    protected static string $resource = HolidayCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
