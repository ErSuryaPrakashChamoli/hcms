<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\ServiceDeskActions;

class ListTickets extends PeopleListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [ServiceDeskActions::askHr()];
    }
}
